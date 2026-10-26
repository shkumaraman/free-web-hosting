import ast
import asyncio
import copy
import hashlib
import heapq
import json
import mimetypes
import os
import random
import re
import time
import types
from contextlib import asynccontextmanager
from datetime import datetime, timezone
from functools import lru_cache
from typing import Any, Dict, List, Optional, Set

from fastapi import (
    Body,
    FastAPI,
    File,
    Form,
    HTTPException,
    Query,
    Request,
    Response,
    UploadFile,
    WebSocket,
    WebSocketDisconnect,
    status,
)
from fastapi.middleware.cors import CORSMiddleware
from fastapi.middleware.gzip import GZipMiddleware
from fastapi.responses import FileResponse, HTMLResponse, JSONResponse, StreamingResponse
from simpleeval import EvalWithCompoundTypes, FeatureNotAvailable

try:
    import orjson
except Exception:
    orjson = None

try:
    import jwt as pyjwt
except Exception:
    pyjwt = None

_hf_fs = None

HF_TOKEN = os.getenv("HF_TOKEN")
BUCKET_NAME = os.getenv("BUCKET_NAME", "plygram/backend")
CLOUD_SYNC_LOCK = asyncio.Lock()
MAX_UPLOAD_SIZE = 50 * 1024 * 1024

AUTH_SECRET = os.getenv("AUTH_SECRET")
AUTH_ALGORITHM = os.getenv("AUTH_ALGORITHM", "HS256")
AUTH_EMULATOR = os.getenv("AUTH_EMULATOR", "false").lower() in ("1", "true", "yes")

DATABASE: Dict[str, Any] = {}
RULES: Dict[str, Any] = {}
INDEX_CACHE: Dict[str, Dict[str, List[tuple]]] = {}
db_lock = asyncio.Lock()

subscriptions: Dict[str, Dict[WebSocket, Optional[Dict[str, Any]]]] = {}
active_websockets: Set[WebSocket] = set()
ws_disconnect_ops: Dict[WebSocket, Dict[str, tuple]] = {}
ws_queues: Dict[WebSocket, asyncio.Queue] = {}
WS_QUEUE_MAX = int(os.getenv("WS_QUEUE_MAX", "300"))
broadcast_queue: Optional[asyncio.Queue] = None

DB_DIRTY = False
PULL_OK = False

STORAGE_DIR = os.getenv("STORAGE_DIR", "./data")
RULES_FILE = os.path.join(STORAGE_DIR, "rules.json")
DB_FILE = os.path.join(STORAGE_DIR, "db.json")
BACKUPS_DIR = os.path.join(STORAGE_DIR, "backups")
UPLOADS_DIR = os.path.join(STORAGE_DIR, "uploads")

os.makedirs(BACKUPS_DIR, exist_ok=True)
os.makedirs(UPLOADS_DIR, exist_ok=True)

try:
    os.chmod(STORAGE_DIR, 0o777)
    os.chmod(UPLOADS_DIR, 0o777)
    os.chmod(BACKUPS_DIR, 0o777)
except Exception:
    pass

PUSH_CHARS = "-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz"
ATTACHMENT_EXTS = {".html", ".htm", ".svg", ".xml", ".xhtml", ".js", ".mjs", ".swf"}


def _clean_str(val: str) -> str:
    try:
        val.encode("utf-8")
        return val
    except UnicodeEncodeError:
        return val.encode("utf-8", "replace").decode("utf-8")


def dumps_bytes(obj: Any) -> bytes:
    if orjson is not None:
        try:
            return orjson.dumps(obj)
        except (TypeError, ValueError):
            pass
    try:
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8", "replace")
    except Exception:
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=True).encode("utf-8")


def dumps_str(obj: Any, indent: Optional[int] = None) -> str:
    if indent is None and orjson is not None:
        try:
            return orjson.dumps(obj).decode("utf-8", "replace")
        except (TypeError, ValueError):
            pass
    try:
        if indent is None:
            return json.dumps(obj, separators=(",", ":"), ensure_ascii=False)
        return json.dumps(obj, indent=indent, ensure_ascii=False)
    except Exception:
        if indent is None:
            return json.dumps(obj, separators=(",", ":"), ensure_ascii=True)
        return json.dumps(obj, indent=indent, ensure_ascii=True)


def jresp(obj: Any, status_code: int = 200, headers: Optional[Dict[str, str]] = None) -> Response:
    return Response(
        content=dumps_bytes(obj),
        status_code=status_code,
        media_type="application/json",
        headers=headers,
    )


class AuthContext:
    def __init__(self, claims: Dict[str, Any]):
        claims = dict(claims or {})
        safe_claims = {k: v for k, v in claims.items() if isinstance(k, str) and k.isidentifier()}
        self.__dict__.update(safe_claims)
        if "uid" not in safe_claims:
            self.uid = claims.get("uid") or claims.get("sub") or claims.get("user_id")
        self.token = types.SimpleNamespace(**safe_claims)

    def __repr__(self):
        return f"AuthContext(uid={getattr(self, 'uid', None)!r})"


def _extract_raw_token(headers, query_params) -> Optional[str]:
    auth_header = headers.get("authorization") or headers.get("Authorization")
    if auth_header and auth_header.lower().startswith("bearer "):
        return auth_header[7:].strip()
    q_token = query_params.get("auth") or query_params.get("access_token")
    if q_token:
        return q_token
    return None


def _resolve_auth_from_token(raw_token: Optional[str]) -> Optional[AuthContext]:
    if not raw_token:
        return None
    if AUTH_SECRET and pyjwt is not None:
        try:
            claims = pyjwt.decode(raw_token, AUTH_SECRET, algorithms=[AUTH_ALGORITHM])
            return AuthContext(claims)
        except Exception:
            return None
    if AUTH_EMULATOR:
        try:
            claims = json.loads(raw_token)
            if isinstance(claims, dict):
                return AuthContext(claims)
        except Exception:
            return None
    return None


def resolve_auth(request: Request) -> Optional[AuthContext]:
    raw_token = _extract_raw_token(request.headers, request.query_params)
    return _resolve_auth_from_token(raw_token)


def resolve_auth_ws(websocket: WebSocket) -> Optional[AuthContext]:
    raw_token = _extract_raw_token(websocket.headers, websocket.query_params)
    return _resolve_auth_from_token(raw_token)


def generate_etag(data: Any) -> str:
    if data is None:
        return '"null"'
    raw = None
    if orjson is not None:
        try:
            raw = orjson.dumps(data, option=orjson.OPT_SORT_KEYS)
        except (TypeError, ValueError):
            raw = None
    if raw is None:
        try:
            raw = json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8", "replace")
        except Exception:
            raw = json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")
    return '"' + hashlib.md5(raw).hexdigest() + '"'


def wants_etag(request: Request) -> bool:
    h = request.headers
    return bool(h.get("if-match") or h.get("if-none-match") or h.get("x-firebase-etag"))


def get_hf_fs():
    global _hf_fs
    if not HF_TOKEN or HF_TOKEN.startswith("hf_YOUR"):
        return None
    if _hf_fs is not None:
        return _hf_fs
    try:
        from huggingface_hub import HfFileSystem
        _hf_fs = HfFileSystem(token=HF_TOKEN)
        return _hf_fs
    except Exception:
        return None


def _push_single_file_sync(local_path: str, remote_rel_path: str) -> bool:
    fs = get_hf_fs()
    if not fs:
        return False
    target_path = f"hf://buckets/{BUCKET_NAME}/{remote_rel_path.lstrip('/')}"
    try:
        fs.put_file(local_path, target_path)
        return True
    except Exception:
        return False


async def push_single_file_to_hf(local_path: str, remote_rel_path: str) -> bool:
    return await asyncio.to_thread(_push_single_file_sync, local_path, remote_rel_path)


def _delete_sync(remote_rel_path: str) -> bool:
    fs = get_hf_fs()
    if not fs:
        return False
    target_path = f"hf://buckets/{BUCKET_NAME}/{remote_rel_path.lstrip('/')}"
    try:
        if fs.exists(target_path):
            fs.rm(target_path)
        return True
    except Exception:
        return False


async def run_cloud_delete(relative_path: str):
    await asyncio.to_thread(_delete_sync, relative_path)


async def run_state_sync(direction: str, files_to_push: Optional[List[str]] = None):
    global PULL_OK
    if not HF_TOKEN or HF_TOKEN.startswith("hf_YOUR"):
        PULL_OK = True
        return

    def _sync():
        global PULL_OK
        fs = get_hf_fs()
        if not fs:
            PULL_OK = True
            return
        base_remote = f"hf://buckets/{BUCKET_NAME}"
        state_files = files_to_push or ["db.json", "rules.json"]

        if direction == "pull":
            pull_succeeded = False
            for attempt in range(3):
                try:
                    for sf in ["db.json", "rules.json"]:
                        rf = f"{base_remote}/{sf}"
                        lf = os.path.join(STORAGE_DIR, sf)
                        if fs.exists(rf):
                            fs.get_file(rf, lf)

                    remote_backups = f"{base_remote}/backups"
                    if fs.exists(remote_backups):
                        backup_files = fs.ls(remote_backups, detail=False)
                        for rbf in backup_files:
                            fname = os.path.basename(rbf)
                            local_bf = os.path.join(BACKUPS_DIR, fname)
                            if fname.endswith(".json") and not os.path.exists(local_bf):
                                fs.get_file(rbf, local_bf)
                    pull_succeeded = True
                    break
                except Exception:
                    time.sleep(1)

            if pull_succeeded or os.path.exists(DB_FILE):
                PULL_OK = True

        elif direction == "push":
            if not PULL_OK:
                return
            for sf in state_files:
                lf = os.path.join(STORAGE_DIR, sf)
                rf = f"{base_remote}/{sf}"
                if os.path.isfile(lf):
                    try:
                        fs.put_file(lf, rf)
                    except Exception:
                        pass

    try:
        async with CLOUD_SYNC_LOCK:
            await asyncio.to_thread(_sync)
    except Exception:
        pass


def strip_json_suffix(path: str) -> str:
    if path.endswith(".json"):
        return path[:-5]
    return path


def _atomic_write_bytes(path: str, data: bytes):
    tmp_path = f"{path}.tmp-{os.getpid()}-{random.randint(0, 999999)}"
    try:
        with open(tmp_path, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp_path, path)
    except Exception:
        if os.path.exists(tmp_path):
            try:
                os.remove(tmp_path)
            except Exception:
                pass
        raise


def _atomic_write_text(path: str, text: str):
    _atomic_write_bytes(path, text.encode("utf-8", "replace"))


def _safe_backup_path(filename: str) -> str:
    clean_name = os.path.basename(filename)
    backups_abs = os.path.abspath(BACKUPS_DIR)
    full_path = os.path.abspath(os.path.join(backups_abs, clean_name))
    if not full_path.startswith(backups_abs + os.sep):
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid filename")
    return full_path


def server_now_ms() -> int:
    return int(time.time() * 1000)


def generate_push_id() -> str:
    now_ms = server_now_ms()
    time_chars = [""] * 8
    for i in range(7, -1, -1):
        time_chars[i] = PUSH_CHARS[now_ms % 64]
        now_ms //= 64
    rand_chars = "".join(random.choices(PUSH_CHARS, k=12))
    return "".join(time_chars) + rand_chars


def _is_number(val: Any) -> bool:
    return isinstance(val, (int, float)) and not isinstance(val, bool)


def is_server_value(val: Any) -> bool:
    return isinstance(val, dict) and len(val) == 1 and ".sv" in val


def resolve_server_values(val: Any, existing: Any = None, now_ms: Optional[int] = None) -> Any:
    if now_ms is None:
        now_ms = server_now_ms()
    if is_server_value(val):
        spec = val[".sv"]
        if spec == "timestamp":
            return now_ms
        if isinstance(spec, dict) and "increment" in spec:
            delta = spec["increment"]
            if _is_number(delta):
                base = existing if _is_number(existing) else 0
                return base + delta
        return None
    if isinstance(val, dict):
        resolved = {}
        for k, v in val.items():
            clean_k = _clean_str(str(k)) if isinstance(k, str) else str(k)
            child_existing = existing.get(clean_k) if isinstance(existing, dict) else None
            resolved[clean_k] = resolve_server_values(v, child_existing, now_ms)
        return resolved
    if isinstance(val, list):
        items = []
        for i, v in enumerate(val):
            child_existing = existing.get(str(i)) if isinstance(existing, dict) else None
            items.append(resolve_server_values(v, child_existing, now_ms))
        return items
    return val


def normalize_firebase_data(val: Any) -> Any:
    if val is None:
        return None
    if isinstance(val, str):
        return _clean_str(val)
    if isinstance(val, list):
        val = {str(i): v for i, v in enumerate(val)}
    if isinstance(val, dict):
        cleaned = {}
        for k, v in val.items():
            clean_k = _clean_str(str(k)) if isinstance(k, str) else str(k)
            nv = normalize_firebase_data(v)
            if nv is not None:
                cleaned[clean_k] = nv
        return cleaned if cleaned else None
    return val


def get_nested(data: dict, path_parts: List[str]) -> Any:
    current = data
    for part in path_parts:
        if not isinstance(current, dict) or part not in current:
            return None
        current = current[part]
    return current


def _with_set(node: Any, parts: List[str], val: Any) -> Any:
    if not parts:
        return val
    new_node = dict(node) if isinstance(node, dict) else {}
    head = parts[0]
    sub = _with_set(new_node.get(head), parts[1:], val)
    if sub is None:
        new_node.pop(head, None)
    else:
        new_node[head] = sub
    return new_node if new_node else None


class RuleDataSnapshot:
    def __init__(self, value: Any, root_val: Any = None, path: Optional[List[str]] = None):
        self._value = value
        self._root = root_val if root_val is not None else value
        self._path = path or []

    def val(self) -> Any:
        return self._value

    def exists(self) -> bool:
        return self._value is not None

    def child(self, path: str) -> "RuleDataSnapshot":
        parts = [p for p in str(path).strip("/").split("/") if p]
        new_path = self._path + parts
        if not isinstance(self._value, dict):
            return RuleDataSnapshot(None, self._root, new_path)
        curr = self._value
        for p in parts:
            if isinstance(curr, dict) and p in curr:
                curr = curr[p]
            else:
                return RuleDataSnapshot(None, self._root, new_path)
        return RuleDataSnapshot(curr, self._root, new_path)

    def parent(self) -> "RuleDataSnapshot":
        if not self._path:
            return self
        parent_path = self._path[:-1]
        parent_val = get_nested(self._root, parent_path) if isinstance(self._root, dict) else None
        return RuleDataSnapshot(parent_val, self._root, parent_path)

    def hasChild(self, path: str) -> bool:
        return self.child(path).exists()

    def hasChildren(self, keys: Any = None) -> bool:
        if not isinstance(self._value, dict):
            return False
        if keys is None:
            return len(self._value) > 0
        if isinstance(keys, (list, tuple)):
            return all(str(k) in self._value for k in keys)
        return False

    def isString(self) -> bool:
        return isinstance(self._value, str)

    def isNumber(self) -> bool:
        return isinstance(self._value, (int, float)) and not isinstance(self._value, bool)

    def isBoolean(self) -> bool:
        return isinstance(self._value, bool)

    def contains(self, sub: Any) -> bool:
        if isinstance(self._value, (str, list, dict)):
            return str(sub) in self._value
        return False

    def beginsWith(self, prefix: Any) -> bool:
        return isinstance(self._value, str) and self._value.startswith(str(prefix))

    def endsWith(self, suffix: Any) -> bool:
        return isinstance(self._value, str) and self._value.endswith(str(suffix))

    def replace(self, old: Any, new: Any) -> str:
        if not isinstance(self._value, str):
            return ""
        return self._value.replace(str(old), str(new))

    def toLowerCase(self) -> str:
        return self._value.lower() if isinstance(self._value, str) else ""

    def toUpperCase(self) -> str:
        return self._value.upper() if isinstance(self._value, str) else ""

    def matches(self, pattern: Any) -> bool:
        if not isinstance(self._value, str) or len(self._value) > 2000:
            return False
        try:
            pat_str = str(pattern)
            flags = 0
            if pat_str.startswith("/") and pat_str.rfind("/") > 0:
                last_slash = pat_str.rfind("/")
                flag_str = pat_str[last_slash + 1 :]
                pat_str = pat_str[1:last_slash]
                if "i" in flag_str:
                    flags |= re.IGNORECASE
                if "m" in flag_str:
                    flags |= re.MULTILINE
                if "s" in flag_str:
                    flags |= re.DOTALL
            return bool(re.search(pat_str, self._value, flags))
        except Exception:
            return False

    def length(self) -> int:
        if isinstance(self._value, (str, list, dict)):
            return len(self._value)
        return 0


class SafeRuleEval(EvalWithCompoundTypes):
    ALLOWED_METHODS = {
        "val",
        "child",
        "parent",
        "exists",
        "hasChild",
        "hasChildren",
        "isString",
        "isNumber",
        "isBoolean",
        "contains",
        "beginsWith",
        "endsWith",
        "replace",
        "toLowerCase",
        "toUpperCase",
        "matches",
        "length",
    }

    def _eval_attribute(self, node):
        obj = self._eval(node.value)
        if node.attr == "length":
            if isinstance(obj, RuleDataSnapshot):
                return obj.length()
            if isinstance(obj, (str, list, dict)):
                return len(obj)
            return 0
        return super()._eval_attribute(node)

    def _eval_call(self, node):
        if isinstance(node.func, ast.Attribute):
            attr = node.func.attr
            if attr in self.ALLOWED_METHODS:
                obj = self._eval(node.func.value)
                if not isinstance(obj, RuleDataSnapshot):
                    obj = RuleDataSnapshot(obj)
                method = getattr(obj, attr)
                args = [self._eval(a) for a in node.args]
                return method(*args)
            raise FeatureNotAvailable(f"Method '{attr}' is not permitted")
        return super()._eval_call(node)


def _transform_ternary(s: str) -> str:
    s = s.strip()
    if "?" not in s:
        return s

    p_depth = 0
    t_depth = 0
    first_q = -1
    matching_colon = -1

    for i, ch in enumerate(s):
        if ch == "(":
            p_depth += 1
        elif ch == ")":
            p_depth -= 1
        elif p_depth == 0:
            if ch == "?":
                if first_q == -1:
                    first_q = i
                else:
                    t_depth += 1
            elif ch == ":":
                if first_q != -1:
                    if t_depth == 0:
                        matching_colon = i
                        break
                    else:
                        t_depth -= 1

    if first_q != -1 and matching_colon != -1:
        cond = s[:first_q].strip()
        true_val = s[first_q + 1 : matching_colon].strip()
        false_val = s[matching_colon + 1 :].strip()
        return f"({_transform_ternary(true_val)} if {_transform_ternary(cond)} else {_transform_ternary(false_val)})"

    res = []
    p_depth = 0
    start = -1
    last_end = 0

    for i, ch in enumerate(s):
        if ch == "(":
            if p_depth == 0:
                res.append(s[last_end:i])
                start = i + 1
            p_depth += 1
        elif ch == ")":
            p_depth -= 1
            if p_depth == 0 and start != -1:
                inner = s[start:i]
                res.append(f"({_transform_ternary(inner)})")
                last_end = i + 1

    res.append(s[last_end:])
    return "".join(res)


@lru_cache(maxsize=2048)
def _transform_rule_expr(expr: str) -> str:
    expr = expr.strip()
    expr = re.sub(
        r"\.matches\(\s*(/(?:\\.|[^/\\\r\n])+/[gimsuy]*)\s*\)",
        lambda m: f".matches({json.dumps(m.group(1))})",
        expr,
    )

    str_map: Dict[str, str] = {}

    def _save_str(m):
        tok = f"__STRLIT_{len(str_map)}__"
        str_map[tok] = m.group(0)
        return tok

    s = re.sub(r'("(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\')', _save_str, expr)

    s = re.sub(r"===", "==", s)
    s = re.sub(r"!==", "!=", s)
    s = re.sub(r"&&", " and ", s)
    s = re.sub(r"\|\|", " or ", s)
    s = re.sub(r"(?<![!=<>])!(?![=])", " not ", s)
    s = re.sub(r"\btrue\b", "True", s)
    s = re.sub(r"\bfalse\b", "False", s)
    s = re.sub(r"\bnull\b", "None", s)
    s = re.sub(r"\$([a-zA-Z_][a-zA-Z0-9_]*)", r"\1", s)

    s = _transform_ternary(s)

    for tok, orig in str_map.items():
        s = s.replace(tok, orig)

    return s


def load_rules():
    global RULES
    candidates = [
        RULES_FILE,
        "rules.json",
    ]
    for path in candidates:
        if os.path.exists(path):
            try:
                with open(path, "r", encoding="utf-8") as f:
                    RULES = json.load(f)
                    return
            except Exception:
                pass

    RULES = {"rules": {".read": True, ".write": True}}
    try:
        _atomic_write_text(RULES_FILE, dumps_str(RULES, indent=2))
    except Exception:
        pass


def load_database():
    if os.path.exists(DB_FILE):
        try:
            with open(DB_FILE, "rb") as f:
                content = f.read().strip()
                if content:
                    DATABASE.clear()
                    try:
                        raw = orjson.loads(content) if orjson is not None else json.loads(content.decode("utf-8", "replace"))
                    except Exception:
                        raw = json.loads(content.decode("utf-8", "replace"))
                    loaded = normalize_firebase_data(raw)
                    if isinstance(loaded, dict):
                        DATABASE.update(loaded)
        except Exception:
            DATABASE.clear()


def _write_db_sync(snapshot_bytes: bytes) -> bool:
    try:
        _atomic_write_bytes(DB_FILE, snapshot_bytes)
        return True
    except Exception:
        return False


async def flush_now():
    global DB_DIRTY
    files_to_push = []
    snapshot_bytes = None

    async with db_lock:
        if DB_DIRTY:
            DB_DIRTY = False
            snapshot_bytes = dumps_bytes(DATABASE)

    if snapshot_bytes is not None:
        ok = await asyncio.to_thread(_write_db_sync, snapshot_bytes)
        if ok:
            files_to_push.append("db.json")
        else:
            async with db_lock:
                DB_DIRTY = True

    if files_to_push:
        await run_state_sync("push", files_to_push=files_to_push)


async def state_flusher():
    while True:
        await asyncio.sleep(300)
        try:
            await flush_now()
        except Exception:
            pass


async def _raw_send(ws: WebSocket, text: str) -> bool:
    try:
        await asyncio.wait_for(ws.send_text(text), timeout=10.0)
        return True
    except Exception:
        return False


def _drop_ws(ws: WebSocket):
    active_websockets.discard(ws)
    for p in list(subscriptions.keys()):
        subs = subscriptions.get(p)
        if subs is not None:
            subs.pop(ws, None)
            if not subs:
                subscriptions.pop(p, None)


async def _send_ws_safe(ws: WebSocket, text: str) -> bool:
    q = ws_queues.get(ws)
    if q is None:
        return await _raw_send(ws, text)
    try:
        q.put_nowait(text)
        return True
    except asyncio.QueueFull:
        ws_queues.pop(ws, None)
        _drop_ws(ws)
        while not q.empty():
            try:
                q.get_nowait()
            except Exception:
                break
        try:
            q.put_nowait(None)
        except Exception:
            pass
        asyncio.create_task(ws.close(code=status.WS_1008_POLICY_VIOLATION))
        return False


async def _ws_writer(ws: WebSocket, q: asyncio.Queue):
    try:
        while True:
            text = await q.get()
            if text is None:
                break
            if not await _raw_send(ws, text):
                break
    finally:
        ws_queues.pop(ws, None)
        _drop_ws(ws)
        try:
            await ws.close()
        except Exception:
            pass


async def broadcaster_worker():
    global broadcast_queue
    while True:
        item = await broadcast_queue.get()
        if item is None:
            break
        clean_target, value, event_type, ts = item
        target_parts = [p for p in clean_target.split("/") if p] if clean_target else []

        payload_cache: Dict[tuple, str] = {}

        def get_serialized(rel_path: str, evt: str, data_val: Any) -> str:
            cache_key = (rel_path, evt, id(data_val))
            if cache_key not in payload_cache:
                payload_cache[cache_key] = dumps_str(
                    {"path": rel_path, "event": evt, "data": data_val, "serverTime": ts}
                )
            return payload_cache[cache_key]

        query_cache: Dict[tuple, set] = {}

        for sub_path, sock_map in list(subscriptions.items()):
            if not sock_map:
                subscriptions.pop(sub_path, None)
                continue

            sub_parts = [p for p in sub_path.split("/") if p] if sub_path else []

            is_exact = clean_target == sub_path
            is_root_sub = sub_path == ""
            is_child_target = clean_target.startswith(sub_path + "/")
            is_ancestor_target = sub_path.startswith(clean_target + "/") or clean_target == ""

            if not (is_exact or is_root_sub or is_child_target or is_ancestor_target):
                continue

            for ws, q_spec in list(sock_map.items()):
                if is_exact or is_root_sub:
                    rel_path = clean_target if is_root_sub else ""
                    if q_spec and isinstance(value, dict):
                        filtered_val = limit_snapshot(value, q_spec)
                        payload_str = dumps_str(
                            {"path": rel_path, "event": event_type, "data": filtered_val, "serverTime": ts}
                        )
                    else:
                        payload_str = get_serialized(rel_path, event_type, value)
                    await _send_ws_safe(ws, payload_str)

                elif is_child_target:
                    rel_path = clean_target[len(sub_path) + 1 :]
                    child_key = rel_path.split("/")[0]

                    if q_spec and event_type != "delete":
                        async with db_lock:
                            raw_parent = get_nested(DATABASE, sub_parts) if sub_parts else DATABASE
                            parent_copy = dict(raw_parent) if isinstance(raw_parent, dict) else None

                        if parent_copy:
                            q_key = (sub_path, tuple(sorted((k, str(v)) for k, v in q_spec.items())))
                            if q_key not in query_cache:
                                query_cache[q_key] = set(limit_snapshot(parent_copy, q_spec).keys())
                            if child_key not in query_cache[q_key]:
                                continue
                        else:
                            continue

                    payload_str = get_serialized(rel_path, event_type, value)
                    await _send_ws_safe(ws, payload_str)

                elif is_ancestor_target:
                    rel_parts = sub_parts[len(target_parts) :]
                    rel_sub_prefix = "/".join(rel_parts)

                    if event_type == "patch" and isinstance(value, dict):
                        matching_patch = {}
                        sub_touched = False
                        for k, v in value.items():
                            k_clean = str(k).strip("/")
                            if k_clean == rel_sub_prefix:
                                sub_touched = True
                                matching_patch = None
                                break
                            elif k_clean.startswith(rel_sub_prefix + "/"):
                                sub_touched = True
                                matching_patch[k_clean[len(rel_sub_prefix) + 1 :]] = v
                            elif rel_sub_prefix.startswith(k_clean + "/"):
                                sub_touched = True
                                matching_patch = None
                                break

                        if not sub_touched:
                            continue

                        if matching_patch is not None and len(matching_patch) > 0:
                            payload_str = dumps_str(
                                {"path": "", "event": "patch", "data": matching_patch, "serverTime": ts}
                            )
                        else:
                            async with db_lock:
                                raw_sub = get_nested(DATABASE, sub_parts)
                                sub_copy = dict(raw_sub) if isinstance(raw_sub, dict) else raw_sub

                            if q_spec and isinstance(sub_copy, dict):
                                sub_copy = limit_snapshot(sub_copy, q_spec)
                            payload_str = dumps_str(
                                {"path": "", "event": "put" if sub_copy is not None else "delete", "data": sub_copy, "serverTime": ts}
                            )
                    elif isinstance(value, dict):
                        sub_data = get_nested(value, rel_parts)
                        if q_spec and isinstance(sub_data, dict):
                            sub_data = limit_snapshot(sub_data, q_spec)
                        payload_str = dumps_str(
                            {"path": "", "event": "put" if sub_data is not None else "delete", "data": sub_data, "serverTime": ts}
                        )
                    else:
                        payload_str = get_serialized("", "delete", None)

                    await _send_ws_safe(ws, payload_str)


@asynccontextmanager
async def lifespan(app: FastAPI):
    global broadcast_queue
    broadcast_queue = asyncio.Queue(maxsize=5000)
    await run_state_sync("pull")
    load_rules()
    load_database()

    flusher_task = asyncio.create_task(state_flusher())
    broadcaster_task = asyncio.create_task(broadcaster_worker())
    try:
        yield
    finally:
        flusher_task.cancel()
        if broadcast_queue is not None:
            await broadcast_queue.put(None)
        try:
            await flusher_task
        except asyncio.CancelledError:
            pass
        try:
            await broadcaster_task
        except Exception:
            pass
        await flush_now()


app = FastAPI(title="Realtime Database", lifespan=lifespan)

app.add_middleware(GZipMiddleware, minimum_size=500)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
    expose_headers=["ETag"],
)


async def save_db():
    global DB_DIRTY
    DB_DIRTY = True


def _write_rules_sync():
    _atomic_write_text(RULES_FILE, dumps_str(RULES, indent=2))


async def save_rules_file():
    await asyncio.to_thread(_write_rules_sync)
    asyncio.create_task(run_state_sync("push", files_to_push=["rules.json"]))


def delete_nested(data: dict, path_parts: List[str]) -> bool:
    if not path_parts or not isinstance(data, dict):
        return False
    part = path_parts[0]
    if part not in data:
        return False
    if len(path_parts) == 1:
        del data[part]
        return True
    child = data[part]
    if not isinstance(child, dict):
        return False
    deleted = delete_nested(child, path_parts[1:])
    if deleted and not child:
        del data[part]
    return deleted


def set_nested(data: dict, path_parts: List[str], value: Any):
    cleaned = normalize_firebase_data(value)
    if not path_parts:
        data.clear()
        if isinstance(cleaned, dict):
            data.update(copy.deepcopy(cleaned))
        elif cleaned is not None:
            data["data"] = copy.deepcopy(cleaned)
        return
    if cleaned is None:
        delete_nested(data, path_parts)
        return
    current = data
    for part in path_parts[:-1]:
        if part not in current or not isinstance(current[part], dict):
            current[part] = {}
        current = current[part]
    current[path_parts[-1]] = copy.deepcopy(cleaned)


def invalidate_cache(path_parts: List[str]):
    path = "/".join(path_parts)
    if not path:
        INDEX_CACHE.clear()
        return
    keys_to_del = [k for k in INDEX_CACHE if k == path or k.startswith(path + "/") or path.startswith(k + "/")]
    for k in keys_to_del:
        del INDEX_CACHE[k]
    if len(INDEX_CACHE) > 50:
        INDEX_CACHE.clear()


def find_file_record_paths(node: Any, clean_name: str, current_path: Optional[List[str]] = None) -> List[List[str]]:
    if current_path is None:
        current_path = []
    matched_paths: List[List[str]] = []
    if isinstance(node, dict):
        if node.get("filename") == clean_name and isinstance(node.get("url"), str) and "/uploads/" in node["url"]:
            matched_paths.append(current_path)
        else:
            for k, v in node.items():
                matched_paths.extend(find_file_record_paths(v, clean_name, current_path + [str(k)]))
    elif isinstance(node, list):
        for idx, item in enumerate(node):
            matched_paths.extend(find_file_record_paths(item, clean_name, current_path + [str(idx)]))
    return matched_paths


def remove_associated_files(node: Any) -> List[str]:
    deleted_paths = []
    if isinstance(node, dict):
        if (
            "filename" in node
            and isinstance(node["filename"], str)
            and isinstance(node.get("url"), str)
            and f"/uploads/{os.path.basename(node['filename'])}" in node["url"]
            and "id" in node
            and "timestamp" in node
        ):
            fname = os.path.basename(node["filename"])
            fpath = os.path.join(UPLOADS_DIR, fname)
            if os.path.isfile(fpath):
                try:
                    os.remove(fpath)
                except Exception:
                    pass
            deleted_paths.append(f"uploads/{fname}")
        for val in node.values():
            deleted_paths.extend(remove_associated_files(val))
    elif isinstance(node, list):
        for item in node:
            deleted_paths.extend(remove_associated_files(item))
    return deleted_paths


def _build_post_write_root(path_parts: List[str], new_payload: Any, is_patch: bool = False) -> Any:
    virtual: Any = DATABASE
    if is_patch and isinstance(new_payload, dict):
        for rel_key, val in new_payload.items():
            rel_parts = [p for p in str(rel_key).strip("/").split("/") if p]
            full_parts = path_parts + rel_parts
            if not full_parts:
                continue
            cleaned_val = normalize_firebase_data(val)
            virtual = _with_set(virtual, full_parts, cleaned_val) or {}
        return virtual

    cleaned_payload = normalize_firebase_data(new_payload)
    if not path_parts:
        if isinstance(cleaned_payload, dict):
            return cleaned_payload
        return {"data": cleaned_payload} if cleaned_payload is not None else {}
    return _with_set(virtual, path_parts, cleaned_payload) or {}


def eval_firebase_expr(
    expr: Any,
    data_val: Any,
    new_data_val: Any,
    wildcards: Dict[str, str],
    auth: Optional[AuthContext] = None,
    root_val: Any = None,
    new_root_val: Any = None,
    current_path: Optional[List[str]] = None,
) -> bool:
    if isinstance(expr, bool):
        return expr
    if not isinstance(expr, str):
        return False

    transformed = _transform_rule_expr(expr)

    names = {
        "now": server_now_ms(),
        "data": RuleDataSnapshot(data_val, root_val, current_path),
        "newData": RuleDataSnapshot(new_data_val, new_root_val, current_path),
        "root": RuleDataSnapshot(root_val, root_val, []),
        "auth": auth,
    }
    for k, v in wildcards.items():
        names[k] = v
        clean_k = k.lstrip("$")
        names[clean_k] = v
    safe_functions = {"len": len, "int": int, "float": float, "str": str, "bool": bool}
    try:
        evaluator = SafeRuleEval(names=names, functions=safe_functions)
        res = evaluator.eval(transformed)
        return bool(res)
    except Exception:
        return False


def check_read_permission(path_parts: List[str], auth: Optional[AuthContext] = None) -> bool:
    current_rule = RULES.get("rules", {})
    wildcards: Dict[str, str] = {}
    data_val = DATABASE
    root_val = DATABASE
    if ".read" in current_rule:
        if eval_firebase_expr(current_rule[".read"], data_val, None, wildcards, auth, root_val, root_val, []):
            return True
    for i, part in enumerate(path_parts):
        if not isinstance(current_rule, dict):
            break
        current_sub_path = path_parts[: i + 1]
        data_val = get_nested(DATABASE, current_sub_path)
        matched_key = None
        if part in current_rule:
            matched_key = part
        else:
            for k in current_rule.keys():
                if k.startswith("$"):
                    matched_key = k
                    wildcards[k] = part
                    break
        if matched_key:
            current_rule = current_rule[matched_key]
            if isinstance(current_rule, dict) and ".read" in current_rule:
                if eval_firebase_expr(
                    current_rule[".read"], data_val, None, wildcards, auth, root_val, root_val, current_sub_path
                ):
                    return True
        else:
            break
    return False


def check_write_permission(
    path_parts: List[str],
    new_payload: Any,
    auth: Optional[AuthContext] = None,
    is_patch: bool = False,
    new_root_val: Any = None,
) -> bool:
    current_rule = RULES.get("rules", {})
    wildcards: Dict[str, str] = {}
    existing_val = get_nested(DATABASE, path_parts) if path_parts else DATABASE
    root_val = DATABASE
    if new_root_val is None:
        new_root_val = _build_post_write_root(path_parts, new_payload, is_patch=is_patch)
    target_new_root = get_nested(new_root_val, path_parts) if path_parts else (new_root_val or None)
    if ".write" in current_rule:
        if eval_firebase_expr(
            current_rule[".write"], existing_val, target_new_root, wildcards, auth, root_val, new_root_val, path_parts
        ):
            return True
    for i, part in enumerate(path_parts):
        if not isinstance(current_rule, dict):
            break
        current_sub_path = path_parts[: i + 1]
        data_val = get_nested(DATABASE, current_sub_path)
        matched_key = None
        if part in current_rule:
            matched_key = part
        else:
            for k in current_rule.keys():
                if k.startswith("$"):
                    matched_key = k
                    wildcards[k] = part
                    break
        if matched_key:
            current_rule = current_rule[matched_key]
            if isinstance(current_rule, dict) and ".write" in current_rule:
                target_new = get_nested(new_root_val, current_sub_path)
                if eval_firebase_expr(
                    current_rule[".write"],
                    data_val,
                    target_new,
                    wildcards,
                    auth,
                    root_val,
                    new_root_val,
                    current_sub_path,
                ):
                    return True
        else:
            break
    return False


def validate_rules_recursively(
    rule_node: Any,
    data_node: Any,
    wildcards: Dict[str, str],
    auth: Optional[AuthContext] = None,
    root_val: Any = None,
    new_root_val: Any = None,
    current_path: Optional[List[str]] = None,
) -> bool:
    if not isinstance(rule_node, dict) or data_node is None:
        return True
    if ".validate" in rule_node:
        expr = rule_node[".validate"]
        existing_node = get_nested(root_val, current_path) if (isinstance(root_val, dict) and current_path) else None
        if not eval_firebase_expr(
            expr, existing_node, data_node, wildcards, auth, root_val, new_root_val, current_path
        ):
            return False
    if isinstance(data_node, dict):
        for k, v in data_node.items():
            matched_rule = None
            next_wildcards = dict(wildcards)
            if k in rule_node:
                matched_rule = rule_node[k]
            else:
                for rk in rule_node.keys():
                    if rk.startswith("$"):
                        matched_rule = rule_node[rk]
                        next_wildcards[rk] = str(k)
                        break
            if matched_rule and isinstance(matched_rule, dict):
                next_path = (current_path or []) + [str(k)]
                if not validate_rules_recursively(
                    matched_rule, v, next_wildcards, auth, root_val, new_root_val, next_path
                ):
                    return False
    return True


def check_validation(
    path_parts: List[str],
    new_payload: Any,
    auth: Optional[AuthContext] = None,
    is_patch: bool = False,
    new_root_val: Any = None,
) -> bool:
    rules_root = RULES.get("rules", {})
    if not isinstance(rules_root, dict):
        return True

    root_val = DATABASE
    if new_root_val is None:
        new_root_val = _build_post_write_root(path_parts, new_payload, is_patch=is_patch)

    wildcards: Dict[str, str] = {}
    current_rule = rules_root

    if ".validate" in current_rule and new_root_val is not None:
        if not eval_firebase_expr(
            current_rule[".validate"],
            root_val,
            new_root_val,
            wildcards,
            auth,
            root_val,
            new_root_val,
            [],
        ):
            return False

    for i, part in enumerate(path_parts):
        if not isinstance(current_rule, dict):
            break

        matched_key = None
        if part in current_rule:
            matched_key = part
        else:
            for k in current_rule.keys():
                if k.startswith("$"):
                    matched_key = k
                    wildcards[k] = part
                    break

        if not matched_key:
            return True

        current_rule = current_rule[matched_key]
        if not isinstance(current_rule, dict):
            return True

        if i < len(path_parts) - 1:
            current_sub_path = path_parts[: i + 1]
            ancestor_new = get_nested(new_root_val, current_sub_path)
            ancestor_old = get_nested(root_val, current_sub_path)
            if ".validate" in current_rule and ancestor_new is not None:
                if not eval_firebase_expr(
                    current_rule[".validate"],
                    ancestor_old,
                    ancestor_new,
                    wildcards,
                    auth,
                    root_val,
                    new_root_val,
                    current_sub_path,
                ):
                    return False

    target_payload = get_nested(new_root_val, path_parts) if path_parts else (new_root_val or None)
    return validate_rules_recursively(
        current_rule, target_payload, wildcards, auth, root_val, new_root_val, path_parts
    )


def get_index_configuration(path_parts: List[str]) -> Any:
    current = RULES.get("rules", {})
    for part in path_parts:
        if not isinstance(current, dict):
            return None
        if part in current:
            current = current[part]
        else:
            matched = None
            for k in current.keys():
                if k.startswith("$"):
                    matched = k
                    break
            if matched:
                current = current[matched]
            else:
                return None
    if isinstance(current, dict):
        return current.get(".indexOn")
    return None


def _firebase_sort_key(val: Any) -> tuple:
    if val is None:
        return (0, 0)
    if isinstance(val, bool):
        return (1, 1 if val else 0)
    if isinstance(val, (int, float)):
        return (2, float(val))
    if isinstance(val, str):
        return (3, val)
    return (4, dumps_str(val))


def limit_snapshot(data: Any, q: Optional[Dict[str, Any]]) -> Any:
    if not isinstance(data, dict) or not isinstance(q, dict) or not q:
        return data

    try:
        lf = int(q.get("limitToFirst") or 0)
        ll = int(q.get("limitToLast") or 0)
    except Exception:
        lf, ll = 0, 0

    ob = str(q.get("orderBy") or "$key").strip('"\'')

    def parse_bound(v):
        if v is None:
            return None
        if isinstance(v, (int, float, bool)):
            return v
        try:
            return json.loads(v)
        except Exception:
            return str(v).strip('"\'')

    st_val = parse_bound(q.get("startAt"))
    ed_val = parse_bound(q.get("endAt"))
    eq_val = parse_bound(q.get("equalTo"))

    if lf <= 0 and ll <= 0 and st_val is None and ed_val is None and eq_val is None:
        return data

    if ll > 0 and lf <= 0 and ob == "$key" and st_val is None and ed_val is None and eq_val is None:
        keys = heapq.nlargest(ll, data.keys())
        return {k: data[k] for k in sorted(keys)}

    def kf(kv):
        k, v = kv
        if ob == "$key":
            item = k
        elif ob == "$value":
            item = v
        elif isinstance(v, dict):
            item = v.get(ob)
        else:
            item = None
        return (_firebase_sort_key(item), str(k))

    items = sorted(data.items(), key=kf)

    st_key = _firebase_sort_key(st_val) if st_val is not None else None
    ed_key = _firebase_sort_key(ed_val) if ed_val is not None else None

    filtered = []
    for k, v in items:
        if ob == "$key":
            item_val = k
        elif ob == "$value":
            item_val = v
        elif isinstance(v, dict):
            item_val = v.get(ob)
        else:
            item_val = None

        if eq_val is not None and item_val != eq_val:
            continue
        if st_key is not None and _firebase_sort_key(item_val) < st_key:
            continue
        if ed_key is not None and _firebase_sort_key(item_val) > ed_key:
            continue
        filtered.append((k, v))

    if lf > 0:
        filtered = filtered[:lf]
    elif ll > 0:
        filtered = filtered[-ll:]

    return dict(filtered)


async def broadcast(path: str, value: Any, event_type: str = "put"):
    if broadcast_queue is not None:
        item = (path.strip("/"), value, event_type, server_now_ms())
        try:
            broadcast_queue.put_nowait(item)
        except asyncio.QueueFull:
            await broadcast_queue.put(item)


async def execute_disconnect_ops(ops_map: Dict[str, tuple], auth: Optional[AuthContext]):
    if not ops_map:
        return
    broadcasts = []
    del_paths = []
    db_changed = False

    async with db_lock:
        now_ms = server_now_ms()
        for target_clean, (action, payload) in ops_map.items():
            path_parts = [p for p in target_clean.strip("/").split("/") if p]
            if action == "put":
                current_data = get_nested(DATABASE, path_parts) if path_parts else DATABASE
                resolved = resolve_server_values(payload, current_data, now_ms)
                cleaned = normalize_firebase_data(resolved)
                if current_data == cleaned:
                    continue
                new_root = _build_post_write_root(path_parts, cleaned, is_patch=False)
                if not check_write_permission(path_parts, cleaned, auth, is_patch=False, new_root_val=new_root):
                    continue
                if not check_validation(path_parts, cleaned, auth, is_patch=False, new_root_val=new_root):
                    continue
                if cleaned is None:
                    if not path_parts:
                        del_paths.extend(remove_associated_files(DATABASE))
                        DATABASE.clear()
                    else:
                        if current_data is not None:
                            del_paths.extend(remove_associated_files(current_data))
                        delete_nested(DATABASE, path_parts)
                else:
                    set_nested(DATABASE, path_parts, cleaned)
                invalidate_cache(path_parts)
                db_changed = True
                broadcasts.append((target_clean, cleaned, "delete" if cleaned is None else "put"))

            elif action == "patch" and isinstance(payload, dict):
                normalized_patch: Dict[str, Any] = {}
                for k, v in payload.items():
                    rel_parts = [p for p in str(k).strip("/").split("/") if p]
                    existing = get_nested(DATABASE, path_parts + rel_parts)
                    val = normalize_firebase_data(resolve_server_values(v, existing, now_ms))
                    if existing != val:
                        normalized_patch[_clean_str(str(k))] = val

                if not normalized_patch:
                    continue

                new_root = _build_post_write_root(path_parts, normalized_patch, is_patch=True)
                if not check_write_permission(path_parts, normalized_patch, auth, is_patch=True, new_root_val=new_root):
                    continue
                if not check_validation(path_parts, normalized_patch, auth, is_patch=True, new_root_val=new_root):
                    continue
                for rel_key, val in normalized_patch.items():
                    rel_parts = [p for p in str(rel_key).strip("/").split("/") if p]
                    t_parts = path_parts + rel_parts
                    if not t_parts:
                        continue
                    if val is None:
                        target = get_nested(DATABASE, t_parts)
                        if target is not None:
                            del_paths.extend(remove_associated_files(target))
                        delete_nested(DATABASE, t_parts)
                    else:
                        set_nested(DATABASE, t_parts, val)
                    invalidate_cache(t_parts)
                invalidate_cache(path_parts)
                db_changed = True
                broadcasts.append((target_clean, normalized_patch, "patch"))

            elif action == "delete":
                current_data = get_nested(DATABASE, path_parts) if path_parts else DATABASE
                if current_data is None:
                    continue
                new_root = _build_post_write_root(path_parts, None, is_patch=False)
                if not check_write_permission(path_parts, None, auth, is_patch=False, new_root_val=new_root):
                    continue
                if not path_parts:
                    del_paths.extend(remove_associated_files(DATABASE))
                    DATABASE.clear()
                else:
                    target = get_nested(DATABASE, path_parts)
                    if target is not None:
                        del_paths.extend(remove_associated_files(target))
                    delete_nested(DATABASE, path_parts)
                invalidate_cache(path_parts)
                db_changed = True
                broadcasts.append((target_clean, None, "delete"))

        if db_changed:
            await save_db()

    for b_path, b_val, b_evt in broadcasts:
        await broadcast(b_path, b_val, b_evt)

    if del_paths:
        async def _del_files():
            for p in set(del_paths):
                await run_cloud_delete(p)
        asyncio.create_task(_del_files())


HTML_CONSOLE = """<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realtime Database</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #ffffff; color: #202124; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .node-row:hover .actions { display: inline-flex; }
        .actions { display: none; }
        .node-row { font-family: ui-monospace, monospace; font-size: 13px; color: #3c4043; }
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
        </div>
    </header>

    <div id="data-toolbar" class="bg-gray-50 border-b border-gray-200 px-6 py-2.5 flex items-center justify-between gap-3 text-xs">
        <div class="flex items-center gap-2 bg-white border border-gray-300 rounded-md px-3 py-1.5 shadow-xs text-gray-700 mono flex-1 min-w-0 max-w-2xl">
            <div class="overflow-x-auto whitespace-nowrap flex-1 min-w-0 no-scrollbar">
                <span id="base-endpoint" class="font-medium text-gray-800 select-all">https://.../</span>
            </div>
            <button id="copy-btn" onclick="copyUrl()" title="Copy URL" class="ml-2 text-gray-400 hover:text-gray-700 shrink-0">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
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
                    <h2 class="text-sm font-semibold text-gray-800">Security Rules</h2>
                    <p class="text-xs text-gray-500">Edit and publish rules directly to persistent storage.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="loadRules()" class="bg-gray-100 hover:bg-gray-200 border border-gray-300 text-gray-700 px-3 py-1.5 rounded-md text-xs font-medium flex items-center gap-1.5">
                        Discard
                    </button>
                    <button onclick="publishRules()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-md text-xs font-medium flex items-center gap-1.5 shadow-xs">
                        Publish
                    </button>
                </div>
            </div>
            <textarea id="rules-editor" class="w-full flex-1 min-h-[500px] bg-gray-50 text-gray-800 font-mono text-xs p-4 rounded-md border border-gray-300 focus:outline-blue-500" spellcheck="false"></textarea>
        </div>

        <div id="tab-backups" class="hidden min-h-full space-y-4">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-sm font-semibold text-gray-800">Database Backups</h2>
                    <p class="text-xs text-gray-500">Create and manage snapshots of your database.</p>
                </div>
                <button onclick="createBackup()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md text-xs font-medium flex items-center gap-1.5 shadow-xs">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>Create</span>
                </button>
            </div>
            <div class="border border-gray-200 rounded-lg overflow-x-auto w-full">
                <table class="w-full text-left text-xs whitespace-nowrap min-w-max">
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
                    <textarea id="input-val" rows="4" placeholder='Value (e.g. "Hello", 123, true, {"title": "Hello"} or {".sv": "timestamp"})' class="w-full bg-white border border-gray-300 rounded px-3 py-2 font-mono text-gray-800 text-xs focus:outline-blue-500"></textarea>
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
        let pingInterval = null;
        let reconnectTimer = null;
        let modalMode = 'add';
        let expandedPaths = new Set();
        let retryCount = 0;
        document.getElementById('base-endpoint').innerText = window.location.origin + '/';
        const SVG_PLUS = `<svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>`;
        const SVG_EDIT = `<svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>`;
        const SVG_TRASH = `<svg class="w-3 h-3 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>`;

        function toggleMenu() { document.getElementById('dropdown-menu').classList.toggle('hidden'); }
        window.onclick = function(e) {
            if (!e.target.closest('#dropdown-menu') && !e.target.closest('button[onclick*="toggleMenu"]')) {
                const m = document.getElementById('dropdown-menu');
                if (m) m.classList.add('hidden');
            }
        };

        function copyUrl() {
            const url = (document.getElementById('base-endpoint') && document.getElementById('base-endpoint').innerText.trim()) || (window.location.origin + '/');
            const btn = document.getElementById('copy-btn');
            function showSuccess() {
                if (btn) {
                    btn.innerHTML = `<svg class="w-3.5 h-3.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;
                    setTimeout(() => {
                        btn.innerHTML = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>`;
                    }, 1500);
                }
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(showSuccess).catch(() => fallbackCopy(url));
            } else {
                fallbackCopy(url);
            }
            function fallbackCopy(text) {
                try {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showSuccess();
                } catch(e) {}
            }
        }

        function switchTab(tab) {
            ['data', 'rules', 'backups'].forEach(t => {
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
                if (res.ok) alert('Rules published successfully!');
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
            if (res.ok) {
                loadBackups();
            } else {
                const err = await res.json().catch(() => ({}));
                alert('Failed to create backup: ' + (err.detail || 'Error'));
            }
        }

        async function restoreBackup(filename) {
            if (confirm(`Restore ${filename}? This will overwrite current database.`)) {
                const res = await fetch(`/_admin/backups/restore/${filename}`, { method: 'POST' });
                if (res.ok) {
                    refreshData();
                    alert('Backup restored successfully!');
                } else {
                    alert('Failed to restore backup');
                }
            }
        }

        async function deleteBackup(filename) {
            if (confirm(`Delete backup ${filename}?`)) {
                const res = await fetch(`/_admin/backups/${filename}`, { method: 'DELETE' });
                if (res.ok) {
                    loadBackups();
                } else {
                    alert('Failed to delete backup');
                }
            }
        }

        function applyLocalDelta(path, event, data) {
            const parts = path ? path.split('/').filter(Boolean) : [];
            if (event === 'delete' || data === null || data === undefined) {
                if (parts.length === 0) { db = {}; return; }
                let curr = db;
                for (let i = 0; i < parts.length - 1; i++) {
                    if (!curr || typeof curr !== 'object') return;
                    curr = curr[parts[i]];
                }
                if (curr && typeof curr === 'object') delete curr[parts[parts.length - 1]];
            } else if (event === 'put') {
                if (parts.length === 0) { db = data || {}; return; }
                let curr = db;
                for (let i = 0; i < parts.length - 1; i++) {
                    if (!curr[parts[i]] || typeof curr[parts[i]] !== 'object') curr[parts[i]] = {};
                    curr = curr[parts[i]];
                }
                curr[parts[parts.length - 1]] = data;
            } else if (event === 'patch' && data && typeof data === 'object') {
                Object.keys(data).forEach(k => {
                    const subPath = path ? `${path}/${k}` : k;
                    applyLocalDelta(subPath, data[k] === null ? 'delete' : 'put', data[k]);
                });
            }
        }

        function initWebSocket() {
            if (document.hidden) return;
            if (ws && ws.readyState <= 1) return;
            if (reconnectTimer) {
                clearTimeout(reconnectTimer);
                reconnectTimer = null;
            }
            const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const sock = new WebSocket(`${proto}//${window.location.host}/ws/?full=1`);
            ws = sock;
            sock.onopen = () => {
                retryCount = 0;
                if (pingInterval) clearInterval(pingInterval);
                pingInterval = setInterval(() => {
                    if (sock && sock.readyState === WebSocket.OPEN) {
                        sock.send(JSON.stringify({ type: 'ping' }));
                    }
                }, 25000);
            };
            sock.onmessage = (event) => {
                const msg = JSON.parse(event.data);
                if (msg.event === 'time' || msg.event === 'pong' || msg.event === 'ping' || msg.event === 'onDisconnectAck') return;
                if (msg.event === 'connected') {
                    if (!msg.notModified) {
                        db = msg.data || {};
                    }
                    renderTree();
                } else if (msg.event === 'snapshot') {
                    if (!msg.notModified) {
                        applyLocalDelta(msg.path || '', 'put', msg.data);
                        renderTree();
                    }
                } else {
                    applyLocalDelta(msg.path || '', msg.event, msg.data);
                    renderTree();
                }
            };
            sock.onclose = () => {
                if (pingInterval) clearInterval(pingInterval);
                if (sock !== ws) return;
                if (!document.hidden) {
                    const delay = Math.min(30000, 2000 * Math.pow(1.5, retryCount++));
                    reconnectTimer = setTimeout(initWebSocket, delay);
                }
            };
        }

        document.addEventListener("visibilitychange", () => {
            if (document.hidden) {
                if (ws) ws.close();
            } else {
                initWebSocket();
            }
        });

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
                addBtn.className = 'w-5 h-5 rounded hover:bg-gray-200 text-gray-600 flex items-center justify-center';
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
                document.getElementById('input-val').value = JSON.stringify(currentVal);
            }
            document.getElementById('node-modal').classList.remove('hidden');
            document.getElementById('node-modal').classList.add('flex');
        }

        function closeModal() {
            document.getElementById('node-modal').classList.add('hidden');
            document.getElementById('node-modal').classList.remove('flex');
        }

        async function submitModal() {
            const rawPath = document.getElementById('input-path').value.replace(/^\\//, '');
            const rawVal = document.getElementById('input-val').value.trim();
            let parsed;
            if (rawVal === '') {
                parsed = "";
            } else {
                try {
                    parsed = JSON.parse(rawVal);
                } catch (e) {
                    parsed = rawVal;
                }
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
                const res = await fetch(`/${path}.json`, { method: 'DELETE' });
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    alert('Delete failed: ' + (err.detail || 'Permission denied'));
                }
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
            if (confirm('Clear entire database? This cannot be undone.')) {
                const res = await fetch('/.json', { method: 'DELETE' });
                if (!res.ok) {
                    alert('Failed to clear database: Permission denied');
                }
            }
        }
        initWebSocket();
    </script>
</body>
</html>
"""


@app.get("/", response_class=HTMLResponse)
async def serve_dashboard():
    return HTML_CONSOLE


@app.get("/uploads/{filename:path}")
async def serve_upload_file(filename: str):
    clean_name = os.path.basename(filename)
    ext = os.path.splitext(clean_name)[1].lower()
    fpath = os.path.join(UPLOADS_DIR, clean_name)

    headers = {
        "Cache-Control": "public, max-age=31536000, immutable",
        "X-Content-Type-Options": "nosniff",
    }
    if ext in ATTACHMENT_EXTS:
        headers["Content-Disposition"] = f'attachment; filename="{clean_name}"'

    if os.path.isfile(fpath):
        return FileResponse(fpath, headers=headers)

    fs = get_hf_fs()
    if fs:
        remote_path = f"hf://buckets/{BUCKET_NAME}/uploads/{clean_name}"
        exists = await asyncio.to_thread(fs.exists, remote_path)
        if exists:
            content_type, _ = mimetypes.guess_type(clean_name)
            content_type = content_type or "application/octet-stream"

            def _iter_remote():
                with fs.open(remote_path, "rb") as rf:
                    while chunk := rf.read(256 * 1024):
                        yield chunk

            return StreamingResponse(_iter_remote(), media_type=content_type, headers=headers)

    raise HTTPException(status_code=404, detail="File not found")


@app.delete("/uploads/{filename:path}")
async def delete_upload_file(filename: str, request: Request):
    auth = resolve_auth(request)
    if filename.endswith(".json"):
        return await delete_endpoint(f"uploads/{filename}", request)

    clean_name = os.path.basename(filename)
    if not clean_name:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid filename")

    deleted_paths: List[str] = []
    async with db_lock:
        matched_paths = find_file_record_paths(DATABASE, clean_name)
        paths_to_check = matched_paths if matched_paths else [["uploads", os.path.splitext(clean_name)[0]]]

        for p_parts in paths_to_check:
            if not check_write_permission(p_parts, None, auth, is_patch=False):
                raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission denied")

        for p_parts in matched_paths:
            if delete_nested(DATABASE, p_parts):
                invalidate_cache(p_parts)
                deleted_paths.append("/".join(p_parts))

        if deleted_paths:
            invalidate_cache([])
            await save_db()

    for p in deleted_paths:
        await broadcast(p, None, "delete")

    fpath = os.path.join(UPLOADS_DIR, clean_name)
    if os.path.isfile(fpath):
        try:
            os.remove(fpath)
        except Exception:
            pass

    asyncio.create_task(run_cloud_delete(f"uploads/{clean_name}"))

    return {"status": "deleted", "filename": clean_name}


@app.post("/api/upload")
async def upload_media_file(
    request: Request,
    file: UploadFile = File(...),
    target_path: str = Form("uploads"),
):
    auth = resolve_auth(request)
    push_id = generate_push_id()
    original_name = file.filename or "file"
    _, ext = os.path.splitext(original_name)
    saved_filename = f"{push_id}{ext}"
    dest_path = os.path.join(UPLOADS_DIR, saved_filename)
    size = 0

    try:
        with open(dest_path, "wb") as buffer:
            while chunk := await file.read(1024 * 1024):
                size += len(chunk)
                if size > MAX_UPLOAD_SIZE:
                    raise HTTPException(
                        status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
                        detail="File too large (max 50MB)",
                    )
                buffer.write(chunk)
    except Exception as e:
        if os.path.exists(dest_path):
            try:
                os.remove(dest_path)
            except Exception:
                pass
        if isinstance(e, HTTPException):
            raise e
        raise HTTPException(status_code=500, detail=f"Failed to write file: {str(e)}")

    if HF_TOKEN and not HF_TOKEN.startswith("hf_YOUR"):
        file_url = f"https://huggingface.co/buckets/{BUCKET_NAME}/resolve/uploads/{saved_filename}"
    else:
        base_url = str(request.base_url).rstrip("/")
        file_url = f"{base_url}/uploads/{saved_filename}"

    record = {
        "id": push_id,
        "name": original_name,
        "filename": saved_filename,
        "url": file_url,
        "content_type": file.content_type,
        "size": size,
        "timestamp": server_now_ms(),
    }

    path_parts = [p for p in target_path.strip("/").split("/") if p] + [push_id]
    async with db_lock:
        new_root = _build_post_write_root(path_parts, record, is_patch=False)
        if not check_write_permission(path_parts, record, auth, new_root_val=new_root):
            if os.path.exists(dest_path):
                os.remove(dest_path)
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission denied")
        if not check_validation(path_parts, record, auth, new_root_val=new_root):
            if os.path.exists(dest_path):
                os.remove(dest_path)
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Validation failed")

    is_hf_configured = bool(HF_TOKEN and not HF_TOKEN.startswith("hf_YOUR"))
    if is_hf_configured:
        pushed = await push_single_file_to_hf(dest_path, f"uploads/{saved_filename}")
        if not pushed:
            if os.path.exists(dest_path):
                try:
                    os.remove(dest_path)
                except Exception:
                    pass
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail="Cloud storage push failed. Please try again.",
            )
        if os.path.exists(dest_path):
            try:
                os.remove(dest_path)
            except Exception:
                pass

    clean_path = "/".join(path_parts)
    async with db_lock:
        new_root = _build_post_write_root(path_parts, record, is_patch=False)
        if not check_write_permission(path_parts, record, auth, new_root_val=new_root) or not check_validation(
            path_parts, record, auth, new_root_val=new_root
        ):
            if os.path.exists(dest_path):
                try:
                    os.remove(dest_path)
                except Exception:
                    pass
            if is_hf_configured:
                asyncio.create_task(run_cloud_delete(f"uploads/{saved_filename}"))
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission or validation failed")

        set_nested(DATABASE, path_parts, record)
        invalidate_cache(path_parts)
        await save_db()

    await broadcast(clean_path, record, "put")
    return jresp({"status": "success", "path": clean_path, "data": record})


@app.get("/api-data")
@app.get("/api-data/")
@app.get("/api-data/{full_path:path}")
async def get_raw_data(request: Request, full_path: str = ""):
    auth = resolve_auth(request)
    path_parts = [p for p in full_path.strip("/").split("/") if p]
    if not check_read_permission(path_parts, auth):
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission denied")
    data = DATABASE if not path_parts else get_nested(DATABASE, path_parts)
    if data is None:
        data = {}

    headers = {}
    if wants_etag(request):
        etag = generate_etag(data)
        headers["ETag"] = etag
        if_none_match = request.headers.get("if-none-match")
        if if_none_match and if_none_match == etag:
            return Response(status_code=304, headers=headers)

    return jresp(data, headers=headers)


@app.get("/_admin/rules")
async def get_rules():
    load_rules()
    return jresp(RULES)


@app.post("/_admin/rules")
async def update_rules(new_rules: Dict[str, Any] = Body(...)):
    global RULES
    RULES = new_rules
    await save_rules_file()
    return jresp({"status": "success", "rules": RULES})


@app.get("/_admin/backups")
async def list_backups():
    files_map = {}
    if os.path.isdir(BACKUPS_DIR):
        for f in os.listdir(BACKUPS_DIR):
            if f.endswith(".json"):
                fp = os.path.join(BACKUPS_DIR, f)
                stat = os.stat(fp)
                files_map[f] = {
                    "filename": f,
                    "date": datetime.fromtimestamp(stat.st_mtime, timezone.utc).strftime("%b %d, %Y %I:%M %p"),
                    "size": stat.st_size,
                }

    def _fetch_remote():
        fs = get_hf_fs()
        if not fs:
            return []
        remote_backups = f"hf://buckets/{BUCKET_NAME}/backups"
        try:
            if fs.exists(remote_backups):
                items = fs.ls(remote_backups, detail=True)
                res = []
                for it in items:
                    name = os.path.basename(it.get("name", ""))
                    if name.endswith(".json"):
                        size = it.get("size", 0)
                        mtime = it.get("last_modified")
                        if isinstance(mtime, datetime):
                            d_str = mtime.strftime("%b %d, %Y %I:%M %p")
                        else:
                            d_str = datetime.now(timezone.utc).strftime("%b %d, %Y %I:%M %p")
                        res.append({"filename": name, "date": d_str, "size": size})
                return res
        except Exception:
            pass
        return []

    remote_items = await asyncio.to_thread(_fetch_remote)
    for item in remote_items:
        if item["filename"] not in files_map:
            files_map[item["filename"]] = item

    return sorted(list(files_map.values()), key=lambda x: x["filename"], reverse=True)


@app.post("/_admin/backups")
async def make_backup():
    filename = f"backup_{datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')}.json"
    dest = _safe_backup_path(filename)
    async with db_lock:
        snapshot_bytes = dumps_bytes(DATABASE)
    await asyncio.to_thread(_atomic_write_bytes, dest, snapshot_bytes)
    await push_single_file_to_hf(dest, f"backups/{filename}")
    return {"status": "created", "filename": filename}


@app.post("/_admin/backups/restore/{filename}")
async def restore_backup(filename: str):
    src = _safe_backup_path(filename)
    if not os.path.exists(src):
        def _fetch_backup():
            fs = get_hf_fs()
            if fs:
                remote_path = f"hf://buckets/{BUCKET_NAME}/backups/{os.path.basename(filename)}"
                try:
                    if fs.exists(remote_path):
                        fs.get_file(remote_path, src)
                except Exception:
                    pass
        await asyncio.to_thread(_fetch_backup)

    if not os.path.exists(src):
        raise HTTPException(status_code=404, detail="Backup file not found")

    def _read():
        with open(src, "rb") as f:
            raw = f.read()
            return orjson.loads(raw) if orjson is not None else json.loads(raw.decode("utf-8", "replace"))

    data = normalize_firebase_data(await asyncio.to_thread(_read))
    async with db_lock:
        DATABASE.clear()
        if isinstance(data, dict):
            DATABASE.update(data)
        invalidate_cache([])
        await save_db()
    await flush_now()
    await broadcast("", DATABASE, "put")
    return {"status": "restored"}


@app.get("/_admin/backups/download/{filename}")
async def download_backup(filename: str):
    fp = _safe_backup_path(filename)
    if not os.path.exists(fp):
        def _fetch_backup():
            fs = get_hf_fs()
            if fs:
                remote_path = f"hf://buckets/{BUCKET_NAME}/backups/{os.path.basename(filename)}"
                try:
                    if fs.exists(remote_path):
                        fs.get_file(remote_path, fp)
                except Exception:
                    pass
        await asyncio.to_thread(_fetch_backup)

    if not os.path.exists(fp):
        raise HTTPException(status_code=404, detail="Backup file not found")
    return FileResponse(fp, media_type="application/json", filename=os.path.basename(fp))


@app.delete("/_admin/backups/{filename}")
async def delete_backup(filename: str):
    fp = _safe_backup_path(filename)
    if os.path.exists(fp):
        try:
            os.remove(fp)
        except Exception:
            pass

    asyncio.create_task(run_cloud_delete(f"backups/{filename}"))
    return {"status": "deleted"}


NO_STORE_HEADERS = {"Cache-Control": "no-store, max-age=0"}


@app.get("/api/time")
async def get_server_time(clientTime: Optional[int] = Query(None)):
    now_ms = server_now_ms()
    offset = (now_ms - clientTime) if clientTime is not None else 0
    return JSONResponse(
        {
            "serverTime": now_ms,
            "serverTimeOffset": offset,
            "iso": datetime.fromtimestamp(now_ms / 1000, timezone.utc).isoformat(),
        },
        headers=NO_STORE_HEADERS,
    )


@app.get("/.info/{info_path:path}")
async def info_endpoint(info_path: str, clientTime: Optional[int] = Query(None)):
    key = strip_json_suffix(info_path).strip("/")
    now_ms = server_now_ms()
    if key == "serverTime":
        return JSONResponse(now_ms, headers=NO_STORE_HEADERS)
    if key == "serverTimeOffset":
        offset = (now_ms - clientTime) if clientTime is not None else 0
        return JSONResponse(offset, headers=NO_STORE_HEADERS)
    if key == "connected":
        return JSONResponse(True, headers=NO_STORE_HEADERS)
    raise HTTPException(status_code=404, detail="Unknown info path")


@app.get("/{full_path:path}")
async def read_endpoint(
    full_path: str,
    request: Request,
    shallow: bool = False,
    orderBy: Optional[str] = Query(None),
    equalTo: Optional[str] = Query(None),
    startAt: Optional[str] = Query(None),
    endAt: Optional[str] = Query(None),
    limitToFirst: Optional[int] = Query(None),
    limitToLast: Optional[int] = Query(None),
):
    auth = resolve_auth(request)
    full_path = strip_json_suffix(full_path)
    path_parts = [p for p in full_path.strip("/").split("/") if p]

    if not check_read_permission(path_parts, auth):
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission denied")
    data = DATABASE if not path_parts else get_nested(DATABASE, path_parts)

    headers = {}
    if wants_etag(request):
        etag = generate_etag(data)
        headers["ETag"] = etag
        if_none_match = request.headers.get("if-none-match")
        if if_none_match and if_none_match == etag:
            return Response(status_code=304, headers=headers)

    if data is None:
        return jresp(None, headers=headers)

    if shallow and isinstance(data, dict):
        return jresp({k: True for k in data.keys()}, headers=headers)

    if orderBy and isinstance(data, dict):
        clean_order_by = orderBy.strip('"\'')

        def parse_param(val_str):
            if val_str is None:
                return None
            try:
                return json.loads(val_str)
            except Exception:
                return val_str.strip('"\'')

        eq_val = parse_param(equalTo)
        st_val = parse_param(startAt)
        ed_val = parse_param(endAt)

        path_key = "/".join(path_parts)
        is_indexed = False

        idx_cfg = get_index_configuration(path_parts)
        if isinstance(idx_cfg, list) and clean_order_by in idx_cfg:
            is_indexed = True
        elif isinstance(idx_cfg, str) and clean_order_by == idx_cfg:
            is_indexed = True

        if is_indexed:
            if path_key not in INDEX_CACHE:
                INDEX_CACHE[path_key] = {}

            if clean_order_by not in INDEX_CACHE[path_key]:
                temp_list = []
                for k, v in data.items():
                    if clean_order_by == "$key":
                        item_val = k
                    elif clean_order_by == "$value":
                        item_val = v
                    elif isinstance(v, dict):
                        item_val = v.get(clean_order_by)
                    else:
                        item_val = None
                    temp_list.append((item_val, k, v))

                temp_list.sort(key=lambda x: (_firebase_sort_key(x[0]), str(x[1])))
                INDEX_CACHE[path_key][clean_order_by] = temp_list

            sorted_data = INDEX_CACHE[path_key][clean_order_by]
        else:
            sorted_data = []
            for k, v in data.items():
                if clean_order_by == "$key":
                    item_val = k
                elif clean_order_by == "$value":
                    item_val = v
                elif isinstance(v, dict):
                    item_val = v.get(clean_order_by)
                else:
                    item_val = None
                sorted_data.append((item_val, k, v))

            sorted_data.sort(key=lambda x: (_firebase_sort_key(x[0]), str(x[1])))

        st_key = _firebase_sort_key(st_val) if st_val is not None else None
        ed_key = _firebase_sort_key(ed_val) if ed_val is not None else None

        filtered_list = []
        for item_val, k, v in sorted_data:
            if eq_val is not None and item_val != eq_val:
                continue
            if st_key is not None and _firebase_sort_key(item_val) < st_key:
                continue
            if ed_key is not None and _firebase_sort_key(item_val) > ed_key:
                continue
            filtered_list.append((k, v))

        if limitToFirst and limitToFirst > 0:
            filtered_list = filtered_list[:limitToFirst]
        elif limitToLast and limitToLast > 0:
            filtered_list = filtered_list[-limitToLast:]

        return jresp({k: v for k, v in filtered_list}, headers=headers)

    return jresp(data, headers=headers)


@app.put("/{full_path:path}")
@app.put("/")
async def write_endpoint(request: Request, full_path: str = "", payload: Any = Body(None)):
    auth = resolve_auth(request)
    full_path = strip_json_suffix(full_path)
    path_parts = [p for p in full_path.strip("/").split("/") if p]
    clean_path = "/".join(path_parts)
    del_paths = []
    headers = {}

    async with db_lock:
        current_data = get_nested(DATABASE, path_parts) if path_parts else DATABASE
        if_match = request.headers.get("if-match")
        if if_match and generate_etag(current_data) != if_match:
            raise HTTPException(status_code=412, detail="Precondition Failed (ETag mismatch)")

        now_ms = server_now_ms()
        resolved_payload = resolve_server_values(payload, current_data, now_ms)
        cleaned_payload = normalize_firebase_data(resolved_payload)

        if current_data == cleaned_payload:
            if wants_etag(request):
                headers["ETag"] = generate_etag(cleaned_payload)
            return jresp(cleaned_payload, headers=headers)

        new_root = _build_post_write_root(path_parts, cleaned_payload, is_patch=False)
        if not check_write_permission(path_parts, cleaned_payload, auth, is_patch=False, new_root_val=new_root):
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission denied")
        if not check_validation(path_parts, cleaned_payload, auth, is_patch=False, new_root_val=new_root):
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Validation failed")

        if cleaned_payload is None:
            if not path_parts:
                del_paths.extend(remove_associated_files(DATABASE))
                DATABASE.clear()
            else:
                if current_data is not None:
                    del_paths.extend(remove_associated_files(current_data))
                delete_nested(DATABASE, path_parts)
        else:
            set_nested(DATABASE, path_parts, cleaned_payload)

        invalidate_cache(path_parts)
        await save_db()
        if wants_etag(request):
            headers["ETag"] = generate_etag(cleaned_payload)

    await broadcast(clean_path, cleaned_payload, "delete" if cleaned_payload is None else "put")

    if del_paths:
        async def _del_files():
            for p in set(del_paths):
                await run_cloud_delete(p)
        asyncio.create_task(_del_files())

    return jresp(cleaned_payload, headers=headers)


@app.patch("/{full_path:path}")
async def patch_endpoint(full_path: str, request: Request, payload: Dict[str, Any] = Body(...)):
    auth = resolve_auth(request)
    full_path = strip_json_suffix(full_path)
    base_parts = [p for p in full_path.strip("/").split("/") if p]
    clean_path = "/".join(base_parts)
    del_paths = []

    async with db_lock:
        current_data = get_nested(DATABASE, base_parts) if base_parts else DATABASE
        if_match = request.headers.get("if-match")
        if if_match and generate_etag(current_data) != if_match:
            raise HTTPException(status_code=412, detail="Precondition Failed (ETag mismatch)")

        now_ms = server_now_ms()
        normalized_patch: Dict[str, Any] = {}
        for k, v in payload.items():
            rel_parts = [p for p in str(k).strip("/").split("/") if p]
            existing = get_nested(DATABASE, base_parts + rel_parts)
            val = normalize_firebase_data(resolve_server_values(v, existing, now_ms))
            if existing != val:
                normalized_patch[_clean_str(str(k))] = val

        if not normalized_patch:
            return jresp({})

        new_root = _build_post_write_root(base_parts, normalized_patch, is_patch=True)
        if not check_write_permission(base_parts, normalized_patch, auth, is_patch=True, new_root_val=new_root):
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission denied")
        if not check_validation(base_parts, normalized_patch, auth, is_patch=True, new_root_val=new_root):
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Validation failed")

        for rel_key, val in normalized_patch.items():
            rel_parts = [p for p in str(rel_key).strip("/").split("/") if p]
            target_parts = base_parts + rel_parts
            if not target_parts:
                continue
            if val is None:
                target = get_nested(DATABASE, target_parts)
                if target is not None:
                    del_paths.extend(remove_associated_files(target))
                delete_nested(DATABASE, target_parts)
            else:
                set_nested(DATABASE, target_parts, val)
            invalidate_cache(target_parts)

        invalidate_cache(base_parts)
        await save_db()

    await broadcast(clean_path, normalized_patch, "patch")

    if del_paths:
        async def _del_files():
            for p in set(del_paths):
                await run_cloud_delete(p)
        asyncio.create_task(_del_files())

    return jresp(normalized_patch)


@app.post("/{full_path:path}")
async def post_push_endpoint(full_path: str, request: Request, payload: Any = Body(None)):
    auth = resolve_auth(request)
    full_path = strip_json_suffix(full_path)
    path_parts = [p for p in full_path.strip("/").split("/") if p]
    push_id = generate_push_id()
    target_parts = path_parts + [push_id]
    clean_path = "/".join(target_parts)
    did_write = False

    async with db_lock:
        resolved_payload = resolve_server_values(payload, None, server_now_ms())
        cleaned_payload = normalize_firebase_data(resolved_payload)
        new_root = _build_post_write_root(target_parts, cleaned_payload, is_patch=False)

        if not check_write_permission(target_parts, cleaned_payload, auth, is_patch=False, new_root_val=new_root):
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission denied")
        if not check_validation(target_parts, cleaned_payload, auth, is_patch=False, new_root_val=new_root):
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Validation failed")

        if cleaned_payload is not None:
            set_nested(DATABASE, target_parts, cleaned_payload)
            invalidate_cache(target_parts)
            await save_db()
            did_write = True

    if did_write:
        await broadcast(clean_path, cleaned_payload, "put")

    return jresp({"name": push_id})


@app.delete("/{full_path:path}")
@app.delete("/")
async def delete_endpoint(full_path: str = "", request: Request = None):
    auth = resolve_auth(request) if request is not None else None
    full_path = strip_json_suffix(full_path)
    path_parts = [p for p in full_path.strip("/").split("/") if p]
    clean_path = "/".join(path_parts)
    del_paths = []

    async with db_lock:
        current_data = get_nested(DATABASE, path_parts) if path_parts else DATABASE
        if current_data is None:
            return jresp({"status": "deleted", "path": clean_path})

        if request is not None:
            if_match = request.headers.get("if-match")
            if if_match and generate_etag(current_data) != if_match:
                raise HTTPException(status_code=412, detail="Precondition Failed (ETag mismatch)")

        new_root = _build_post_write_root(path_parts, None, is_patch=False)
        if not check_write_permission(path_parts, None, auth, is_patch=False, new_root_val=new_root):
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Permission denied")

        if not path_parts:
            del_paths.extend(remove_associated_files(DATABASE))
            DATABASE.clear()
        else:
            if current_data is not None:
                del_paths.extend(remove_associated_files(current_data))
            delete_nested(DATABASE, path_parts)

        invalidate_cache(path_parts)
        await save_db()

    await broadcast(clean_path, None, "delete")

    if del_paths:
        async def _del_files():
            for p in set(del_paths):
                await run_cloud_delete(p)
        asyncio.create_task(_del_files())

    return jresp({"status": "deleted", "path": clean_path})


@app.websocket("/ws/{full_path:path}")
@app.websocket("/ws/")
async def ws_endpoint(websocket: WebSocket, full_path: str = ""):
    auth = resolve_auth_ws(websocket)
    path_parts = [p for p in full_path.strip("/").split("/") if p]
    if not check_read_permission(path_parts, auth):
        await websocket.close(code=status.WS_1008_POLICY_VIOLATION)
        return
    await websocket.accept()
    active_websockets.add(websocket)
    ws_disconnect_ops[websocket] = {}
    subscribed_paths: set = set()

    wq: asyncio.Queue = asyncio.Queue(maxsize=WS_QUEUE_MAX)
    ws_queues[websocket] = wq
    writer_task = asyncio.create_task(_ws_writer(websocket, wq))

    clean_path = "/".join(path_parts)
    qp = websocket.query_params
    q_params = {k: qp.get(k) for k in ("orderBy", "limitToFirst", "limitToLast", "startAt", "endAt", "equalTo") if qp.get(k)}
    wants_full = qp.get("full") in ("1", "true")

    if path_parts or wants_full:
        subscriptions.setdefault(clean_path, {})[websocket] = q_params or None
        subscribed_paths.add(clean_path)

        raw_data = get_nested(DATABASE, path_parts) if path_parts else DATABASE
        snap_data = limit_snapshot(raw_data, q_params)

        msg = {"event": "connected", "path": clean_path, "serverTime": server_now_ms()}
        if path_parts:
            et = generate_etag(snap_data)
            msg["etag"] = et
            client_etag = qp.get("etag") or qp.get("hash")
            if client_etag and client_etag.strip('"') == et.strip('"'):
                msg["notModified"] = True

        if not msg.get("notModified"):
            msg["data"] = snap_data

        await _send_ws_safe(websocket, dumps_str(msg))
    else:
        await _send_ws_safe(
            websocket,
            dumps_str({"event": "connected", "path": "", "serverTime": server_now_ms()}),
        )

    try:
        while True:
            try:
                raw_msg = await asyncio.wait_for(websocket.receive_text(), timeout=45.0)
            except asyncio.TimeoutError:
                ping_ok = await _send_ws_safe(
                    websocket, dumps_str({"event": "ping", "serverTime": server_now_ms()})
                )
                if not ping_ok:
                    break
                continue

            try:
                msg = json.loads(raw_msg)
            except Exception:
                continue
            if not isinstance(msg, dict):
                continue

            msg_type = msg.get("type") or msg.get("action")
            if msg_type == "ping":
                await _send_ws_safe(websocket, dumps_str({"event": "pong", "serverTime": server_now_ms()}))

            elif msg_type == "time":
                now_ms = server_now_ms()
                client_time = msg.get("clientTime")
                offset = (now_ms - client_time) if _is_number(client_time) else 0
                await _send_ws_safe(
                    websocket,
                    dumps_str({"event": "time", "serverTime": now_ms, "serverTimeOffset": offset}),
                )

            elif msg_type == "auth":
                token = msg.get("token") or msg.get("cred")
                new_auth = _resolve_auth_from_token(token)
                if token and not new_auth:
                    await _send_ws_safe(websocket, dumps_str({"event": "authAck", "status": "error", "message": "Invalid token"}))
                else:
                    auth = new_auth
                    await _send_ws_safe(websocket, dumps_str({"event": "authAck", "status": "ok", "uid": auth.uid if auth else None}))

            elif msg_type in ("subscribe", "listen", "q"):
                target_path = strip_json_suffix(str(msg.get("path", ""))).strip("/")
                t_parts = [p for p in target_path.split("/") if p]
                if not check_read_permission(t_parts, auth):
                    await _send_ws_safe(websocket, dumps_str({"event": "error", "path": target_path, "message": "Permission denied"}))
                else:
                    raw_q = msg.get("query") if isinstance(msg.get("query"), dict) else msg
                    q_spec = {k: raw_q.get(k) for k in ("orderBy", "limitToFirst", "limitToLast", "startAt", "endAt", "equalTo") if raw_q.get(k) is not None} or None

                    subscriptions.setdefault(target_path, {})[websocket] = q_spec
                    subscribed_paths.add(target_path)

                    raw_data = get_nested(DATABASE, t_parts) if t_parts else DATABASE
                    snap_data = limit_snapshot(raw_data, q_spec)

                    out = {"event": "snapshot", "path": target_path, "serverTime": server_now_ms()}
                    et = generate_etag(snap_data)
                    out["etag"] = et
                    client_hash = msg.get("hash") or msg.get("etag")
                    if client_hash and str(client_hash).strip('"') == et.strip('"'):
                        out["notModified"] = True

                    if not out.get("notModified"):
                        out["data"] = snap_data

                    await _send_ws_safe(websocket, dumps_str(out))

            elif msg_type in ("unsubscribe", "unlisten", "n"):
                target_path = strip_json_suffix(str(msg.get("path", ""))).strip("/")
                subscribed_paths.discard(target_path)
                if target_path in subscriptions:
                    subscriptions[target_path].pop(websocket, None)
                    if not subscriptions[target_path]:
                        del subscriptions[target_path]

            elif msg_type in ("set", "put", "update", "patch", "push", "remove", "delete"):
                raw_target = strip_json_suffix(str(msg.get("path", ""))).strip("/")
                req_id = msg.get("reqId") or msg.get("r")
                t_parts = [p for p in raw_target.split("/") if p]
                clean_t = "/".join(t_parts)
                now_ms = server_now_ms()
                val = msg.get("data")
                del_paths = []
                broadcast_action = None
                ack_response = None

                try:
                    async with db_lock:
                        if msg_type in ("push",):
                            push_id = generate_push_id()
                            target_parts = t_parts + [push_id]
                            clean_t = "/".join(target_parts)
                            resolved_val = resolve_server_values(val, None, now_ms)
                            cleaned_val = normalize_firebase_data(resolved_val)
                            new_root = _build_post_write_root(target_parts, cleaned_val, is_patch=False)
                            if not check_write_permission(target_parts, cleaned_val, auth, is_patch=False, new_root_val=new_root):
                                ack_response = {"event": "writeAck", "reqId": req_id, "status": "error", "error": "Permission denied"}
                            elif not check_validation(target_parts, cleaned_val, auth, is_patch=False, new_root_val=new_root):
                                ack_response = {"event": "writeAck", "reqId": req_id, "status": "error", "error": "Validation failed"}
                            else:
                                if cleaned_val is not None:
                                    set_nested(DATABASE, target_parts, cleaned_val)
                                    invalidate_cache(target_parts)
                                    await save_db()
                                broadcast_action = (clean_t, cleaned_val, "put")
                                ack_response = {"event": "writeAck", "reqId": req_id, "path": clean_t, "name": push_id, "status": "ok"}

                        elif msg_type in ("update", "patch"):
                            if not isinstance(val, dict):
                                ack_response = {"event": "writeAck", "reqId": req_id, "status": "error", "error": "Data must be a dictionary"}
                            else:
                                normalized_patch: Dict[str, Any] = {}
                                for k, v in val.items():
                                    rel_parts = [p for p in str(k).strip("/").split("/") if p]
                                    existing = get_nested(DATABASE, t_parts + rel_parts)
                                    nv = normalize_firebase_data(resolve_server_values(v, existing, now_ms))
                                    if existing != nv:
                                        normalized_patch[_clean_str(str(k))] = nv

                                if not normalized_patch:
                                    ack_response = {"event": "writeAck", "reqId": req_id, "path": clean_t, "status": "ok"}
                                else:
                                    new_root = _build_post_write_root(t_parts, normalized_patch, is_patch=True)
                                    if not check_write_permission(t_parts, normalized_patch, auth, is_patch=True, new_root_val=new_root):
                                        ack_response = {"event": "writeAck", "reqId": req_id, "status": "error", "error": "Permission denied"}
                                    elif not check_validation(t_parts, normalized_patch, auth, is_patch=True, new_root_val=new_root):
                                        ack_response = {"event": "writeAck", "reqId": req_id, "status": "error", "error": "Validation failed"}
                                    else:
                                        for rel_key, pv in normalized_patch.items():
                                            rel_parts = [p for p in str(rel_key).strip("/").split("/") if p]
                                            tp = t_parts + rel_parts
                                            if not tp:
                                                continue
                                            if pv is None:
                                                target = get_nested(DATABASE, tp)
                                                if target is not None:
                                                    del_paths.extend(remove_associated_files(target))
                                                delete_nested(DATABASE, tp)
                                            else:
                                                set_nested(DATABASE, tp, pv)
                                            invalidate_cache(tp)
                                        invalidate_cache(t_parts)
                                        await save_db()
                                        broadcast_action = (clean_t, normalized_patch, "patch")
                                        ack_response = {"event": "writeAck", "reqId": req_id, "path": clean_t, "status": "ok"}

                        elif msg_type in ("set", "put"):
                            current_data = get_nested(DATABASE, t_parts) if t_parts else DATABASE
                            resolved_val = resolve_server_values(val, current_data, now_ms)
                            cleaned_val = normalize_firebase_data(resolved_val)

                            if current_data == cleaned_val:
                                ack_response = {"event": "writeAck", "reqId": req_id, "path": clean_t, "status": "ok"}
                            else:
                                new_root = _build_post_write_root(t_parts, cleaned_val, is_patch=False)
                                if not check_write_permission(t_parts, cleaned_val, auth, is_patch=False, new_root_val=new_root):
                                    ack_response = {"event": "writeAck", "reqId": req_id, "status": "error", "error": "Permission denied"}
                                elif not check_validation(t_parts, cleaned_val, auth, is_patch=False, new_root_val=new_root):
                                    ack_response = {"event": "writeAck", "reqId": req_id, "status": "error", "error": "Validation failed"}
                                else:
                                    if cleaned_val is None:
                                        if not t_parts:
                                            del_paths.extend(remove_associated_files(DATABASE))
                                            DATABASE.clear()
                                        else:
                                            if current_data is not None:
                                                del_paths.extend(remove_associated_files(current_data))
                                            delete_nested(DATABASE, t_parts)
                                    else:
                                        set_nested(DATABASE, t_parts, cleaned_val)
                                    invalidate_cache(t_parts)
                                    await save_db()
                                    broadcast_action = (clean_t, cleaned_val, "delete" if cleaned_val is None else "put")
                                    ack_response = {"event": "writeAck", "reqId": req_id, "path": clean_t, "status": "ok"}

                        elif msg_type in ("remove", "delete"):
                            current_data = get_nested(DATABASE, t_parts) if t_parts else DATABASE
                            if current_data is None:
                                ack_response = {"event": "writeAck", "reqId": req_id, "path": clean_t, "status": "ok"}
                            else:
                                new_root = _build_post_write_root(t_parts, None, is_patch=False)
                                if not check_write_permission(t_parts, None, auth, is_patch=False, new_root_val=new_root):
                                    ack_response = {"event": "writeAck", "reqId": req_id, "status": "error", "error": "Permission denied"}
                                else:
                                    if not t_parts:
                                        del_paths.extend(remove_associated_files(DATABASE))
                                        DATABASE.clear()
                                    else:
                                        del_paths.extend(remove_associated_files(current_data))
                                        delete_nested(DATABASE, t_parts)
                                    invalidate_cache(t_parts)
                                    await save_db()
                                    broadcast_action = (clean_t, None, "delete")
                                    ack_response = {"event": "writeAck", "reqId": req_id, "path": clean_t, "status": "ok"}

                    if del_paths:
                        async def _del_files():
                            for p in set(del_paths):
                                await run_cloud_delete(p)
                        asyncio.create_task(_del_files())

                    if broadcast_action:
                        await broadcast(*broadcast_action)
                    if ack_response:
                        await _send_ws_safe(websocket, dumps_str(ack_response))

                except Exception as e:
                    await _send_ws_safe(websocket, dumps_str({"event": "writeAck", "reqId": req_id, "status": "error", "error": str(e)}))

            elif msg_type in ("onDisconnectPut", "onDisconnectPatch", "onDisconnectDelete", "onDisconnectCancel"):
                raw_target = msg.get("path")
                if raw_target is None:
                    target_clean = clean_path
                else:
                    rel_clean = strip_json_suffix(str(raw_target)).strip("/")
                    if str(raw_target).startswith("/"):
                        target_clean = rel_clean
                    else:
                        target_clean = f"{clean_path}/{rel_clean}".strip("/") if clean_path else rel_clean

                ops = ws_disconnect_ops.setdefault(websocket, {})
                if msg_type == "onDisconnectCancel":
                    ops.pop(target_clean, None)
                elif msg_type == "onDisconnectPut":
                    ops[target_clean] = ("put", msg.get("data"))
                elif msg_type == "onDisconnectPatch":
                    if isinstance(msg.get("data"), dict):
                        ops[target_clean] = ("patch", msg.get("data"))
                elif msg_type == "onDisconnectDelete":
                    ops[target_clean] = ("delete", None)

                await _send_ws_safe(
                    websocket,
                    dumps_str({"event": "onDisconnectAck", "type": msg_type, "path": target_clean}),
                )
    except (WebSocketDisconnect, Exception):
        pass
    finally:
        ws_queues.pop(websocket, None)
        writer_task.cancel()
        _drop_ws(websocket)
        for p in subscribed_paths:
            if p in subscriptions:
                subscriptions[p].pop(websocket, None)
                if not subscriptions[p]:
                    del subscriptions[p]
        pending_ops = ws_disconnect_ops.pop(websocket, {})
        if pending_ops:
            try:
                await execute_disconnect_ops(pending_ops, auth)
            except Exception:
                pass


if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", 8000))
    uvicorn.run("app:app", host="0.0.0.0", port=port, ws_per_message_deflate=True)
