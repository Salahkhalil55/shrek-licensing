# app.py — Flask License API for ShrekAimAssist (Render-ready)
import os, secrets, string
from datetime import datetime, timedelta, timezone
from flask import Flask, request, jsonify, send_from_directory
from flask_cors import CORS
import pymysql
from pymysql.cursors import DictCursor

# ====== Env config (اضبطها من Render → Environment Variables) ======
MYSQL_HOST = os.getenv("MYSQL_HOST", "localhost")
MYSQL_USER = os.getenv("MYSQL_USER", "root")
MYSQL_PASSWORD = os.getenv("MYSQL_PASSWORD", "")
MYSQL_DB = os.getenv("MYSQL_DB", "shrek")
ADMIN_TOKEN = os.getenv("ADMIN_TOKEN", "Sovos0v0")  # غيّرها على Render
TZ = os.getenv("TZ", "UTC")

# ====== App ======
app = Flask(__name__, static_url_path="", static_folder="static")
CORS(app)

# ====== DB helpers ======
def db():
    return pymysql.connect(
        host=MYSQL_HOST, user=MYSQL_USER, password=MYSQL_PASSWORD,
        database=MYSQL_DB, autocommit=True, cursorclass=DictCursor
    )

def now_utc():
    return datetime.now(timezone.utc)

def to_iso(dt):
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")

def gen_key(n=24):
    # مفاتيح Uppercase Hex/Letters
    alphabet = string.ascii_uppercase + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(n))

# ====== SQL bootstrap (تشغيل لمرة واحدة إذا الجدول مش موجود) ======
DDL = """
CREATE TABLE IF NOT EXISTS licenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(64) NOT NULL UNIQUE,
  product VARCHAR(128) NOT NULL,
  version VARCHAR(32) NOT NULL,
  days INT NOT NULL,
  activated_at DATETIME NULL,
  expires_at   DATETIME NULL,
  activated_hwid VARCHAR(256) NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(256),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""

with db() as con:
    with con.cursor() as c:
        c.execute(DDL)

# ====== API Core ======
@app.get("/api")
def api_root_get():
    # اختياري: صفحة فحص بسيطة
    if request.args.get("action") == "ping":
        return jsonify(ok=True, msg="pong", time=to_iso(now_utc()))
    # قدّم ملف الواجهة الإدارية إذا فتح /api بدون باراميترات
    return send_from_directory("static", "admin.html")

@app.post("/api")
def api_root_post():
    action = request.args.get("action", "").strip().lower()
    if not action:
        return jsonify(ok=False, error="no_action"), 400

    # ---- ADMIN ACTIONS (تتطلب توكن) ----
    if action in ("generate", "list", "revoke", "extend"):
        token = (
            request.headers.get("X-Admin-Token")
            or request.args.get("token")
            or (request.json or {}).get("admin")
        )
        if token != ADMIN_TOKEN:
            return jsonify(ok=False, error="unauthorized"), 401

    try:
        payload = request.get_json(silent=True) or {}
    except Exception:
        payload = {}

    with db() as con:
        cur = con.cursor()

        # ---- GENERATE ----
        if action == "generate":
            product = payload.get("product", "ShrekAimAssist")
            version = payload.get("version", "1.0")
            days = int(payload.get("days", 30))
            k = gen_key(24)
            cur.execute(
                "INSERT INTO licenses(`key`,product,version,days) VALUES(%s,%s,%s,%s)",
                (k, product, version, days),
            )
            return jsonify(ok=True, key=k, product=product, version=version, days=days)

        # ---- LIST ----
        if action == "list":
            cur.execute("SELECT * FROM licenses ORDER BY id DESC LIMIT 500")
            rows = cur.fetchall()
            # حول التواريخ لISO
            for r in rows:
                for col in ("activated_at", "expires_at", "created_at"):
                    if r.get(col):
                        r[col] = r[col].replace(tzinfo=timezone.utc) if r[col].tzinfo is None else r[col]
                        r[col] = to_iso(r[col])
            return jsonify(ok=True, items=rows)

        # ---- REVOKE ----
        if action == "revoke":
            key = payload.get("key", "").strip()
            cur.execute("UPDATE licenses SET revoked=1 WHERE `key`=%s", (key,))
            return jsonify(ok=True, key=key)

        # ---- EXTEND ----
        if action == "extend":
            key = payload.get("key", "").strip()
            add_days = int(payload.get("days", 1))
            # لو ما فيه expires بعد، استخدم activated_at + days
            cur.execute("SELECT * FROM licenses WHERE `key`=%s", (key,))
            row = cur.fetchone()
            if not row:
                return jsonify(ok=False, error="invalid_key"), 404
            if row["revoked"]:
                return jsonify(ok=False, error="revoked"), 400
            base = row["expires_at"] or row["activated_at"] or datetime.utcnow()
            new_exp = base + timedelta(days=add_days)
            cur.execute("UPDATE licenses SET expires_at=%s WHERE `key`=%s", (new_exp, key))
            return jsonify(ok=True, key=key, expires_at=to_iso(new_exp))

        # ---- ACTIVATE ----
        if action == "activate":
            key = payload.get("key", "").strip()
            hwid = payload.get("hwid", "").strip()
            ver = payload.get("version", "").strip()
            if not key or not hwid:
                return jsonify(ok=False, error="missing_params"), 400
            cur.execute("SELECT * FROM licenses WHERE `key`=%s", (key,))
            row = cur.fetchone()
            if not row:
                return jsonify(ok=False, error="invalid_key"), 404
            if row["revoked"]:
                return jsonify(ok=False, error="revoked"), 400
            # أول تفعيل
            if row["activated_at"] is None:
                act = now_utc()
                exp = act + timedelta(days=int(row["days"]))
                cur.execute(
                    "UPDATE licenses SET activated_at=%s, expires_at=%s, activated_hwid=%s, version=%s WHERE `key`=%s",
                    (act, exp, hwid, ver or row["version"], key),
                )
                return jsonify(ok=True, activated_at=to_iso(act), expires_at=to_iso(exp))
            # تفعيل سابق موجود
            if row["activated_hwid"] and row["activated_hwid"] != hwid:
                return jsonify(ok=False, error="hwid_mismatch"), 403
            if row["expires_at"] and row["expires_at"] < datetime.utcnow():
                return jsonify(ok=False, error="expired"), 400
            # صالح
            return jsonify(ok=True, activated_at=to_iso(row["activated_at"]), expires_at=to_iso(row["expires_at"]))

        # ---- VALIDATE ----
        if action == "validate":
            key = payload.get("key", "").strip()
            if not key:
                return jsonify(ok=False, error="missing_params"), 400
            cur.execute("SELECT * FROM licenses WHERE `key`=%s", (key,))
            row = cur.fetchone()
            if not row:
                return jsonify(ok=False, error="invalid_key"), 404
            if row["revoked"]:
                return jsonify(ok=False, error="revoked"), 400
            if row["activated_at"] is None:
                return jsonify(ok=False, error="not_activated"), 400
            if row["expires_at"] and row["expires_at"] < datetime.utcnow():
                return jsonify(ok=False, error="expired"), 400
            return jsonify(
                ok=True,
                product=row["product"],
                version=row["version"],
                activated_at=to_iso(row["activated_at"]),
                expires_at=to_iso(row["expires_at"]),
                hwid=row["activated_hwid"],
            )

        return jsonify(ok=False, error="unknown_action"), 400

# ====== Static admin (يخدم admin.html من مجلد static) ======
@app.get("/")
def index():
    return send_from_directory("static", "admin.html")

if __name__ == "__main__":
    port = int(os.getenv("PORT", 10000))
    app.run(host="0.0.0.0", port=port)
