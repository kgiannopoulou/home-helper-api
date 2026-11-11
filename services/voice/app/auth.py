"""Who may call the service.

- Laravel calls the HTTP endpoints with ``Authorization: Bearer <VOICE_SERVICE_TOKEN>``.
- A browser opens the wake-word socket with a short-lived *ticket* that Laravel
  signed for that person (App\\Voice\\WakeTicket): ``<user id>.<expires>.<hmac>``,
  HMAC-SHA256 over ``wake:<user id>:<expires>`` with the same token. The browser
  never sees the token, and a ticket is useless after it expires.
"""

from __future__ import annotations

import hashlib
import hmac
import time


def bearer_ok(header: str | None, token: str) -> bool:
    if not token or not header or not header.startswith("Bearer "):
        return False
    return hmac.compare_digest(header[7:], token)


def sign_ticket(user_id: int, expires: int, token: str) -> str:
    sig = hmac.new(token.encode(), f"wake:{user_id}:{expires}".encode(), hashlib.sha256).hexdigest()
    return f"{user_id}.{expires}.{sig}"


def verify_ticket(ticket: str | None, token: str, now: float | None = None) -> int | None:
    """The user id the ticket was issued to, or None when it is forged, malformed or expired."""
    if not token or not ticket:
        return None
    parts = ticket.split(".")
    if len(parts) != 3 or not parts[0].isdigit() or not parts[1].isdigit():
        return None
    user_id, expires = int(parts[0]), int(parts[1])
    if expires < (now if now is not None else time.time()):
        return None
    if not hmac.compare_digest(sign_ticket(user_id, expires, token), ticket):
        return None
    return user_id
